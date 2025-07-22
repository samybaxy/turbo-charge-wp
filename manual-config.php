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
     * Get all available pages, posts, and menu items
     */
    public static function get_all_site_items() {
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
        
        // All published pages
        $pages = get_pages(array(
            'post_status' => 'publish',
            'number' => 500,
            'sort_column' => 'menu_order'
        ));
        
        foreach ($pages as $page) {
            $items['page_' . $page->ID] = array(
                'type' => 'page',
                'title' => '📄 ' . $page->post_title,
                'url' => get_permalink($page->ID),
                'pattern' => parse_url(get_permalink($page->ID), PHP_URL_PATH),
                'id' => $page->ID,
                'priority' => 3
            );
        }
        
        // Recent posts (limit to 50 for performance)
        $posts = get_posts(array(
            'post_status' => 'publish',
            'numberposts' => 50,
            'post_type' => 'post',
            'orderby' => 'date',
            'order' => 'DESC'
        ));
        
        foreach ($posts as $post) {
            $items['post_' . $post->ID] = array(
                'type' => 'post',
                'title' => '📝 ' . $post->post_title,
                'url' => get_permalink($post->ID),
                'pattern' => parse_url(get_permalink($post->ID), PHP_URL_PATH),
                'id' => $post->ID,
                'priority' => 4
            );
        }
        
        // WooCommerce pages
        if (class_exists('WooCommerce')) {
            $woo_pages = array(
                'shop' => get_option('woocommerce_shop_page_id'),
                'cart' => get_option('woocommerce_cart_page_id'),
                'checkout' => get_option('woocommerce_checkout_page_id'),
                'account' => get_option('woocommerce_myaccount_page_id'),
            );
            
            foreach ($woo_pages as $key => $page_id) {
                if ($page_id) {
                    $items['woo_' . $key] = array(
                        'type' => 'woocommerce',
                        'title' => '🛍️ ' . get_the_title($page_id),
                        'url' => get_permalink($page_id),
                        'pattern' => $key,
                        'id' => $page_id,
                        'priority' => 2
                    );
                }
            }
        }
        
        // Custom post types and their archives
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
            
            // Add individual posts (limit for performance)
            $posts = get_posts(array(
                'post_type' => $post_type->name,
                'post_status' => 'publish',
                'numberposts' => 20
            ));
            
            foreach ($posts as $post) {
                $items[$post_type->name . '_' . $post->ID] = array(
                    'type' => 'custom_post',
                    'title' => '📋 ' . $post->post_title . ' (' . $post_type->label . ')',
                    'url' => get_permalink($post->ID),
                    'pattern' => parse_url(get_permalink($post->ID), PHP_URL_PATH),
                    'id' => $post->ID,
                    'post_type' => $post_type->name,
                    'priority' => 5
                );
            }
        }
        
        // Custom taxonomies
        $taxonomies = get_taxonomies(array(
            'public' => true,
            '_builtin' => false
        ), 'objects');
        
        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms(array(
                'taxonomy' => $taxonomy->name,
                'hide_empty' => false,
                'number' => 20
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
        
        // Menu items from all menus
        $menus = wp_get_nav_menus();
        foreach ($menus as $menu) {
            $menu_items = wp_get_nav_menu_items($menu->term_id);
            if ($menu_items) {
                foreach ($menu_items as $menu_item) {
                    if ($menu_item->url && !isset($items['menu_' . $menu_item->ID])) {
                        $url_path = parse_url($menu_item->url, PHP_URL_PATH);
                        // Create unique pattern using URL path and menu item ID to avoid conflicts
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
                    }
                }
            }
        }
        
        // Sort by priority and title
        uasort($items, function($a, $b) {
            if ($a['priority'] == $b['priority']) {
                return strcmp($a['title'], $b['title']);
            }
            return $a['priority'] - $b['priority'];
        });
        
        return $items;
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
        
        // Get current tab
        $current_tab = $_GET['tab'] ?? 'site_pages';
        
        ?>
        <div class="wrap">
            <h1>🎯 Manual Plugin Configuration</h1>
            <p>Comprehensive control over plugin loading for every page, post, and menu item on your site.</p>
            
            <!-- Tab Navigation -->
            <h2 class="nav-tab-wrapper">
                <a href="?page=tcwp-manual-config&tab=site_pages" class="nav-tab <?php echo $current_tab === 'site_pages' ? 'nav-tab-active' : ''; ?>">
                    📄 Site Pages & Posts
                </a>
                <a href="?page=tcwp-manual-config&tab=url_patterns" class="nav-tab <?php echo $current_tab === 'url_patterns' ? 'nav-tab-active' : ''; ?>">
                    🔗 URL Patterns
                </a>
                <a href="?page=tcwp-manual-config&tab=bulk_actions" class="nav-tab <?php echo $current_tab === 'bulk_actions' ? 'nav-tab-active' : ''; ?>">
                    ⚡ Bulk Actions
                </a>
            </h2>
            
            <div id="tcwp-loading" style="display: none; text-align: center; padding: 40px; color: #666;">
            <div style="font-size: 18px; margin-bottom: 10px;">⏳ Loading configuration...</div>
            <div style="font-size: 14px;">Please wait while we prepare your plugin settings.</div>
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
            debug: true
        };
        
        jQuery(document).ready(function($) {
            var saveTimeout;
            var $saveStatus = $('#tcwp-save-status');
            var isCurrentlySaving = false;
            
            // Initialize grid visibility after DOM is ready - smooth transition
            setTimeout(function() {
                $('#tcwp-loading').hide();
                $('#tcwp-content').show();
                $('.tcwp-config-grid').addClass('loaded');
            }, 100); // Small delay to prevent flash
            
            // Tab persistence functionality
            var urlParams = new URLSearchParams(window.location.search);
            var tabFromUrl = urlParams.get('filter_tab');
            var currentTab = tabFromUrl || localStorage.getItem('tcwp_current_tab') || 'all';
            
            // Store the current tab
            localStorage.setItem('tcwp_current_tab', currentTab);
            
            if (currentTab !== 'all') {
                // Activate the stored tab
                $('.tcwp-filter-tabs button[data-filter="' + currentTab + '"]').click();
            }
            
            // Store current tab when clicked
            $('.tcwp-filter-tabs button').on('click', function() {
                var filterType = $(this).data('filter');
                localStorage.setItem('tcwp_current_tab', filterType);
            });
            
            // Search functionality
            $('#tcwp-search').on('input', function() {
                var searchTerm = $(this).val().toLowerCase();
                $('.tcwp-config-item').each(function() {
                    var title = $(this).find('h4').text().toLowerCase();
                    var url = $(this).find('.url-info').text().toLowerCase();
                    
                    if (title.includes(searchTerm) || url.includes(searchTerm)) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
            });
            
            // Filter by type
            $('.tcwp-filter-tabs button').click(function() {
                var filterType = $(this).data('filter');
                
                $('.tcwp-filter-tabs button').removeClass('active');
                $(this).addClass('active');
                
                if (filterType === 'all') {
                    $('.tcwp-config-item').show();
                } else {
                    $('.tcwp-config-item').hide();
                    $('.tcwp-config-item[data-type="' + filterType + '"]').show();
                }
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
                            
                            // Initialize array only if it doesn't exist
                            if (!allPlugins[pattern]) {
                                allPlugins[pattern] = [];
                            }
                            
                            // Collect checked plugins for this pattern - use specific pattern in selector
                            var pluginSelector = 'input[name="manual_config_plugins[' + pattern + '][]"]:checked';                            
                            var $checkedPlugins = $item.find(pluginSelector);
                            if ( $checkedPlugins.length > 0 ) {
                                $checkedPlugins.each(function() {
                                    var pluginValue = $(this).val();
                                    console.log('TCWP Debug: Adding plugin:', pluginValue);
                                    allPlugins[pattern].push(pluginValue);
                                });
                            }
                        }
                    }
                });
                
                // Add patterns to form data
                patterns.forEach(function(pattern) {
                    formData.append('manual_config_patterns[]', pattern);
                });
                
                // Add plugins to form data
                Object.keys(allPlugins).forEach(function(pattern) {
                    allPlugins[pattern].forEach(function(plugin) {
                        formData.append('manual_config_plugins[' + pattern + '][]', plugin);
                    });
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
                                    // Store current tab before reload
                                    var currentTab = $('.tcwp-filter-tabs button.active').data('filter') || 'all';
                                    localStorage.setItem('tcwp_current_tab', currentTab);
                                    
                                    // Show loading indicator before reload
                                    $('#tcwp-content').fadeOut(200, function() {
                                        $('#tcwp-loading').show();
                                        window.location.reload();
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
                        $saveStatus.text('❌ Connection error during save').css('color', '#d63638');
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
                
                // Perform immediate save
                performAutosave();
                
                // Prevent form submission
                return false;
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
        ?>
        <div class="tcwp-search-box">
            <input type="text" id="tcwp-search" placeholder="🔍 Search pages, posts, and menu items..." />
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
            if (isset($fresh_manual_config[$pattern]) && !empty($fresh_manual_config[$pattern])) {
                $configured_count++;
                $configured_patterns[] = $pattern;
                $plugin_counts[$pattern] = count($fresh_manual_config[$pattern]);
                $total_plugins_configured += $plugin_counts[$pattern];
                
                // Debug info for this specific pattern
                error_log('TCWP Debug: Pattern "' . $pattern . '" has ' . $plugin_counts[$pattern] . ' plugins configured');
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
                    $is_configured = !empty($selected_plugins);
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
            
            <p>
                <button type="button" id="tcwp-manual-save" class="button button-primary">💾 Save All Configurations</button>
                <button type="button" id="tcwp-bulk-essential" class="button button-secondary">⚡ Set Essential Plugins for All</button>
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
                echo '<div class="notice notice-success"><p>✅ WooCommerce pages configured with ' . count($woo_plugins) . ' plugins!</p></div>';
                break;
                
            case 'clear_all':
                delete_option('tcwp_manual_config');
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
     * Get manual configuration for a URL (enhanced)
     */
    public static function get_manual_plugins_for_url($url) {
        $manual_config = get_option('tcwp_manual_config', array());
        $required_plugins = array();
        
        // Parse the current URL
        $parsed_url = parse_url($url);
        $path = $parsed_url['path'] ?? '/';
        
        // First, try exact URL path match
        if (isset($manual_config[$path])) {
            $required_plugins = array_merge($required_plugins, $manual_config[$path]);
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
                }
            } else {
                // Regular pattern matching - check if pattern is contained in URL
                if (strpos($url, $pattern) !== false || strpos($path, $pattern) !== false) {
                    $required_plugins = array_merge($required_plugins, $plugins);
                }
            }
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
}

// Initialize manual configuration
add_action('admin_menu', array('TCWP_Manual_Config', 'add_admin_menu'));
