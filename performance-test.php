<?php
/**
 * Performance Test Module for Turbo Charge WP
 * 
 * Provides performance testing functionality
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Performance Test Handler
 */
class TCWP_Performance_Test {
    
    /**
     * Add performance test to admin menu
     */
    public static function add_admin_menu() {
        add_submenu_page(
            'turbo-charge-wp',
            'Performance Test',
            'Performance Test',
            'manage_options',
            'tcwp-performance-test',
            array(__CLASS__, 'admin_page')
        );
    }
    
    /**
     * Performance test admin page
     */
    public static function admin_page() {
        ?>
        <div class="wrap">
            <h1>🧪 Performance Test</h1>
            <p>Test the performance impact of Turbo Charge WP plugin filtering.</p>
            
            <div class="notice notice-info">
                <p><strong>Note:</strong> This test simulates frontend plugin loading to show the performance impact.</p>
            </div>
            
            <div class="postbox">
                <h3 class="hndle">Performance Test Results</h3>
                <div class="inside">
                    <table class="widefat fixed">
                        <thead>
                            <tr>
                                <th>Test Type</th>
                                <th>Plugins Before</th>
                                <th>Plugins After</th>
                                <th>Optimization</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Simulate performance test
                            $all_plugins = get_option('active_plugins', array());
                            $total_plugins = count($all_plugins);
                            
                            // Simulate filtering with current settings
                            $tcwp = TurboChargeWP::get_instance();
                            
                            // Enable frontend simulation
                            add_filter('tcwp_is_frontend_simulation', '__return_true');
                            
                            // Test homepage
                            $_SERVER['REQUEST_URI'] = '/';
                            $filtered_plugins = $tcwp->ultra_filter_plugins($all_plugins);
                            $homepage_count = count($filtered_plugins);
                            $homepage_optimization = $total_plugins > 0 ? round((($total_plugins - $homepage_count) / $total_plugins) * 100, 1) : 0;
                            
                            // Test WooCommerce shop page
                            $_SERVER['REQUEST_URI'] = '/shop/';
                            $filtered_plugins = $tcwp->ultra_filter_plugins($all_plugins);
                            $shop_count = count($filtered_plugins);
                            $shop_optimization = $total_plugins > 0 ? round((($total_plugins - $shop_count) / $total_plugins) * 100, 1) : 0;
                            
                            // Test blog page
                            $_SERVER['REQUEST_URI'] = '/blog/';
                            $filtered_plugins = $tcwp->ultra_filter_plugins($all_plugins);
                            $blog_count = count($filtered_plugins);
                            $blog_optimization = $total_plugins > 0 ? round((($total_plugins - $blog_count) / $total_plugins) * 100, 1) : 0;
                            
                            // Disable frontend simulation
                            remove_filter('tcwp_is_frontend_simulation', '__return_true');
                            
                            // Reset REQUEST_URI
                            $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=tcwp-performance-test';
                            ?>
                            <tr>
                                <td><strong>Homepage (/)</strong></td>
                                <td><?php echo $total_plugins; ?></td>
                                <td><?php echo $homepage_count; ?></td>
                                <td><?php echo $homepage_optimization; ?>% reduction</td>
                            </tr>
                            <tr>
                                <td><strong>Shop Page (/shop/)</strong></td>
                                <td><?php echo $total_plugins; ?></td>
                                <td><?php echo $shop_count; ?></td>
                                <td><?php echo $shop_optimization; ?>% reduction</td>
                            </tr>
                            <tr>
                                <td><strong>Blog Page (/blog/)</strong></td>
                                <td><?php echo $total_plugins; ?></td>
                                <td><?php echo $blog_count; ?></td>
                                <td><?php echo $blog_optimization; ?>% reduction</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="postbox">
                <h3 class="hndle">Performance Recommendations</h3>
                <div class="inside">
                    <?php
                    $avg_optimization = ($homepage_optimization + $shop_optimization + $blog_optimization) / 3;
                    
                    if ($avg_optimization > 50) {
                        echo '<p style="color: #00a32a;"><strong>Excellent!</strong> Turbo Charge WP is providing significant performance improvements (average ' . round($avg_optimization, 1) . '% plugin reduction).</p>';
                    } elseif ($avg_optimization > 25) {
                        echo '<p style="color: #dba617;"><strong>Good!</strong> Turbo Charge WP is providing moderate performance improvements (average ' . round($avg_optimization, 1) . '% plugin reduction).</p>';
                    } else {
                        echo '<p style="color: #d63638;"><strong>Consider Manual Configuration:</strong> For better performance, consider using the Manual Configuration feature to specify exactly which plugins load on which pages.</p>';
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php
    }
}

// Initialize performance test
add_action('admin_menu', array('TCWP_Performance_Test', 'add_admin_menu'));
