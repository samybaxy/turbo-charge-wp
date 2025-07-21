<?php
/**
 * Page Tester Module for Turbo Charge WP
 * 
 * Provides page-specific testing functionality
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Page Tester Handler
 */
class TCWP_Page_Tester {
    
    /**
     * Add page tester to admin menu
     */
    public static function add_admin_menu() {
        add_submenu_page(
            'turbo-charge-wp',
            'Page Tester',
            'Page Tester',
            'manage_options',
            'tcwp-page-tester',
            array(__CLASS__, 'admin_page')
        );
    }
    
    /**
     * Page tester admin page
     */
    public static function admin_page() {
        // Handle form submission
        $test_results = array();
        if (isset($_POST['test_url']) && !empty($_POST['test_url'])) {
            $test_url = sanitize_text_field($_POST['test_url']);
            $test_results = self::test_page($test_url);
        }
        
        ?>
        <div class="wrap">
            <h1>📊 Page Tester</h1>
            <p>Test how Turbo Charge WP affects plugin loading on specific pages.</p>
            
            <div class="postbox">
                <h3 class="hndle">Test a Specific Page</h3>
                <div class="inside">
                    <form method="post">
                        <table class="form-table">
                            <tr>
                                <th scope="row">Page URL to Test</th>
                                <td>
                                    <input type="text" name="test_url" value="<?php echo isset($_POST['test_url']) ? esc_attr($_POST['test_url']) : '/'; ?>" style="width: 100%;" placeholder="e.g., /, /shop/, /contact/" />
                                    <p class="description">Enter the relative URL path to test (e.g., /, /shop/, /contact/)</p>
                                </td>
                            </tr>
                        </table>
                        <?php submit_button('Test Page'); ?>
                    </form>
                </div>
            </div>
            
            <?php if (!empty($test_results)): ?>
            <div class="postbox">
                <h3 class="hndle">Test Results for: <?php echo esc_html($test_results['url']); ?></h3>
                <div class="inside">
                    <table class="widefat fixed">
                        <thead>
                            <tr>
                                <th>Metric</th>
                                <th>Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Total Active Plugins</strong></td>
                                <td><?php echo $test_results['total_plugins']; ?></td>
                            </tr>
                            <tr>
                                <td><strong>Plugins That Would Load</strong></td>
                                <td><?php echo $test_results['filtered_plugins_count']; ?></td>
                            </tr>
                            <tr>
                                <td><strong>Plugins Filtered Out</strong></td>
                                <td><?php echo $test_results['filtered_out_count']; ?></td>
                            </tr>
                            <tr>
                                <td><strong>Performance Improvement</strong></td>
                                <td><?php echo $test_results['optimization_percent']; ?>% reduction</td>
                            </tr>
                            <tr>
                                <td><strong>Filter Time</strong></td>
                                <td><?php echo $test_results['filter_time']; ?>ms</td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <h4>Plugins That Would Load:</h4>
                    <div style="background: #f9f9f9; padding: 10px; border-radius: 4px; max-height: 200px; overflow-y: auto;">
                        <?php if (!empty($test_results['filtered_plugins'])): ?>
                            <?php foreach ($test_results['filtered_plugins'] as $plugin): ?>
                                <div style="color: #00a32a; font-family: monospace; font-size: 12px; margin: 2px 0;">
                                    ✅ <?php echo esc_html($plugin); ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="color: #666;">No plugins would load on this page.</div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($test_results['filtered_out'])): ?>
                    <h4>Plugins Filtered Out:</h4>
                    <div style="background: #f9f9f9; padding: 10px; border-radius: 4px; max-height: 200px; overflow-y: auto;">
                        <?php foreach ($test_results['filtered_out'] as $plugin): ?>
                            <div style="color: #d63638; font-family: monospace; font-size: 12px; margin: 2px 0;">
                                ❌ <?php echo esc_html($plugin); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="postbox">
                <h3 class="hndle">Common Test URLs</h3>
                <div class="inside">
                    <p>Click on these common URLs to test them quickly:</p>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <a href="?page=tcwp-page-tester&test_url=/" class="button">Homepage (/)</a>
                        <a href="?page=tcwp-page-tester&test_url=/shop/" class="button">Shop (/shop/)</a>
                        <a href="?page=tcwp-page-tester&test_url=/blog/" class="button">Blog (/blog/)</a>
                        <a href="?page=tcwp-page-tester&test_url=/contact/" class="button">Contact (/contact/)</a>
                        <a href="?page=tcwp-page-tester&test_url=/about/" class="button">About (/about/)</a>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Test a specific page
     */
    private static function test_page($url) {
        $start_time = microtime(true);
        
        // Get all active plugins
        $all_plugins = get_option('active_plugins', array());
        $total_plugins = count($all_plugins);
        
        // Set up test environment
        $original_uri = $_SERVER['REQUEST_URI'] ?? '';
        $_SERVER['REQUEST_URI'] = $url;
        
        // Enable frontend simulation
        add_filter('tcwp_is_frontend_simulation', '__return_true');
        
        // Get TurboChargeWP instance and test filtering
        $tcwp = TurboChargeWP::get_instance();
        $filtered_plugins = $tcwp->ultra_filter_plugins($all_plugins);
        
        // Calculate metrics
        $filtered_plugins_count = count($filtered_plugins);
        $filtered_out = array_diff($all_plugins, $filtered_plugins);
        $filtered_out_count = count($filtered_out);
        $optimization_percent = $total_plugins > 0 ? round((($total_plugins - $filtered_plugins_count) / $total_plugins) * 100, 1) : 0;
        $filter_time = round((microtime(true) - $start_time) * 1000, 2);
        
        // Clean up
        remove_filter('tcwp_is_frontend_simulation', '__return_true');
        $_SERVER['REQUEST_URI'] = $original_uri;
        
        return array(
            'url' => $url,
            'total_plugins' => $total_plugins,
            'filtered_plugins' => $filtered_plugins,
            'filtered_plugins_count' => $filtered_plugins_count,
            'filtered_out' => $filtered_out,
            'filtered_out_count' => $filtered_out_count,
            'optimization_percent' => $optimization_percent,
            'filter_time' => $filter_time
        );
    }
}

// Initialize page tester
add_action('admin_menu', array('TCWP_Page_Tester', 'add_admin_menu'));

// Handle quick test URLs
if (isset($_GET['test_url']) && !empty($_GET['test_url'])) {
    $_POST['test_url'] = sanitize_text_field($_GET['test_url']);
}
