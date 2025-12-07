<?php
/**
 * Turbo Charge WP - Standalone Activation Diagnostic
 *
 * This script runs WITHOUT loading WordPress, so it can diagnose issues
 * even when the plugin won't activate.
 *
 * URL: https://yoursite.com/wp-content/plugins/turbo-charge-wp/diagnose-activation.php?key=tcwp2024
 *
 * DELETE THIS FILE AFTER DEBUGGING!
 */

// Simple security - require key parameter
$security_key = 'tcwp2024';
if (!isset($_GET['key']) || $_GET['key'] !== $security_key) {
    die('Access denied. Add ?key=' . $security_key . ' to the URL');
}

header('Content-Type: text/plain; charset=utf-8');
echo "=== Turbo Charge WP Standalone Diagnostic ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// Get paths
$plugin_dir = dirname(__FILE__);
$wp_content_dir = dirname(dirname($plugin_dir));
$mu_plugins_dir = $wp_content_dir . '/mu-plugins';
$wp_config_path = dirname($wp_content_dir) . '/wp-config.php';

echo "=== 1. PATH CHECKS ===\n";
echo "Plugin dir: {$plugin_dir}\n";
echo "WP-Content dir: {$wp_content_dir}\n";
echo "MU-Plugins dir: {$mu_plugins_dir}\n";
echo "wp-config.php: {$wp_config_path}\n\n";

// Check plugin files
echo "=== 2. PLUGIN FILES ===\n";
$plugin_files = [
    'turbo-charge-wp.php',
    'includes/class-plugin-scanner.php',
    'includes/class-detection-cache.php',
    'includes/class-main.php',
    'mu-loader/tcwp-mu-loader.php'
];

$all_files_ok = true;
foreach ($plugin_files as $file) {
    $full_path = $plugin_dir . '/' . $file;
    $exists = file_exists($full_path);
    $readable = $exists && is_readable($full_path);

    echo "- {$file}: ";
    if (!$exists) {
        echo "MISSING!\n";
        $all_files_ok = false;
    } elseif (!$readable) {
        echo "NOT READABLE!\n";
        $all_files_ok = false;
    } else {
        // Check syntax
        $output = [];
        $return_var = 0;
        exec("php -l " . escapeshellarg($full_path) . " 2>&1", $output, $return_var);
        if ($return_var === 0) {
            echo "OK (syntax valid)\n";
        } else {
            echo "SYNTAX ERROR: " . implode(' ', $output) . "\n";
            $all_files_ok = false;
        }
    }
}
echo "\n";

// Check MU-plugins directory
echo "=== 3. MU-PLUGINS DIRECTORY ===\n";
echo "Exists: " . (is_dir($mu_plugins_dir) ? "YES" : "NO") . "\n";
if (is_dir($mu_plugins_dir)) {
    echo "Writable: " . (is_writable($mu_plugins_dir) ? "YES" : "NO") . "\n";
    echo "Permissions: " . substr(sprintf('%o', fileperms($mu_plugins_dir)), -4) . "\n";

    // Check if MU-loader is installed
    $mu_loader_path = $mu_plugins_dir . '/tcwp-mu-loader.php';
    echo "MU-loader installed: " . (file_exists($mu_loader_path) ? "YES" : "NO") . "\n";

    if (file_exists($mu_loader_path)) {
        $mu_content = file_get_contents($mu_loader_path);
        if (preg_match('/@version\s+([0-9.]+)/', $mu_content, $matches)) {
            echo "MU-loader version: " . $matches[1] . "\n";
        }

        // Check if it has the admin bypass
        if (strpos($mu_content, '/wp-admin') !== false) {
            echo "MU-loader has admin URL bypass: YES (good)\n";
        } else {
            echo "MU-loader has admin URL bypass: NO (OLD VERSION - PROBLEM!)\n";
        }
    }
} else {
    echo "MU-plugins directory doesn't exist. Will be created on first activation.\n";
}
echo "\n";

// Try to read wp-config.php to get DB credentials
echo "=== 4. DATABASE CHECK ===\n";
if (!file_exists($wp_config_path)) {
    echo "wp-config.php not found at expected location.\n";
    echo "Trying alternate location...\n";
    $wp_config_path = dirname(dirname($wp_content_dir)) . '/wp-config.php';
}

if (file_exists($wp_config_path)) {
    $wp_config = file_get_contents($wp_config_path);

    // Extract DB credentials
    preg_match("/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $wp_config, $db_name);
    preg_match("/define\s*\(\s*['\"]DB_USER['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $wp_config, $db_user);
    preg_match("/define\s*\(\s*['\"]DB_PASSWORD['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $wp_config, $db_pass);
    preg_match("/define\s*\(\s*['\"]DB_HOST['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $wp_config, $db_host);
    preg_match("/\\\$table_prefix\s*=\s*['\"]([^'\"]+)['\"]\s*;/", $wp_config, $table_prefix);

    if (!empty($db_name[1]) && !empty($db_user[1])) {
        echo "DB credentials found in wp-config.php\n";
        echo "Database: " . $db_name[1] . "\n";
        echo "Table prefix: " . ($table_prefix[1] ?? 'wp_') . "\n";

        // Try to connect
        $mysqli = @new mysqli(
            $db_host[1] ?? 'localhost',
            $db_user[1],
            $db_pass[1] ?? '',
            $db_name[1]
        );

        if ($mysqli->connect_error) {
            echo "DB Connection: FAILED - " . $mysqli->connect_error . "\n";
        } else {
            echo "DB Connection: SUCCESS\n\n";

            $prefix = $table_prefix[1] ?? 'wp_';

            // Check active_plugins option
            echo "=== 5. ACTIVE PLUGINS OPTION ===\n";
            $result = $mysqli->query("SELECT option_value FROM {$prefix}options WHERE option_name = 'active_plugins' LIMIT 1");
            if ($result && $row = $result->fetch_assoc()) {
                $active_plugins = unserialize($row['option_value']);
                if (is_array($active_plugins)) {
                    echo "Total active plugins: " . count($active_plugins) . "\n";
                    $tcwp_active = in_array('turbo-charge-wp/turbo-charge-wp.php', $active_plugins);
                    echo "turbo-charge-wp is active: " . ($tcwp_active ? "YES" : "NO") . "\n";
                    echo "\nActive plugins list:\n";
                    foreach ($active_plugins as $plugin) {
                        $marker = ($plugin === 'turbo-charge-wp/turbo-charge-wp.php') ? ' <-- TCWP' : '';
                        echo "  - {$plugin}{$marker}\n";
                    }
                } else {
                    echo "Could not parse active_plugins option\n";
                }
            } else {
                echo "Could not read active_plugins option\n";
            }
            echo "\n";

            // Check TCWP options
            echo "=== 6. TCWP OPTIONS ===\n";
            $tcwp_options = ['tcwp_enabled', 'tcwp_debug_enabled', 'tcwp_needs_setup', 'tcwp_scan_completed', 'tcwp_essential_plugins'];
            foreach ($tcwp_options as $opt) {
                $result = $mysqli->query("SELECT option_value FROM {$prefix}options WHERE option_name = '{$opt}' LIMIT 1");
                if ($result && $row = $result->fetch_assoc()) {
                    $val = $row['option_value'];
                    if (strlen($val) > 100) {
                        $unserialized = @unserialize($val);
                        if (is_array($unserialized)) {
                            $val = 'Array(' . count($unserialized) . ' items)';
                        } else {
                            $val = substr($val, 0, 50) . '...';
                        }
                    }
                    echo "  {$opt}: {$val}\n";
                } else {
                    echo "  {$opt}: (not set)\n";
                }
            }
            echo "\n";

            // Manual activation test
            echo "=== 7. MANUAL ACTIVATION TEST ===\n";
            if (!$tcwp_active) {
                echo "Plugin is NOT active. Attempting manual activation...\n";

                $active_plugins[] = 'turbo-charge-wp/turbo-charge-wp.php';
                $active_plugins = array_unique($active_plugins);
                $serialized = serialize($active_plugins);

                $stmt = $mysqli->prepare("UPDATE {$prefix}options SET option_value = ? WHERE option_name = 'active_plugins'");
                $stmt->bind_param('s', $serialized);
                $result = $stmt->execute();

                if ($result) {
                    echo "UPDATE query succeeded. Verifying...\n";

                    // Re-read to verify
                    $verify = $mysqli->query("SELECT option_value FROM {$prefix}options WHERE option_name = 'active_plugins' LIMIT 1");
                    if ($verify && $row = $verify->fetch_assoc()) {
                        $verify_plugins = unserialize($row['option_value']);
                        if (in_array('turbo-charge-wp/turbo-charge-wp.php', $verify_plugins)) {
                            echo "SUCCESS! Plugin is now in the active_plugins list.\n";
                            echo "Refresh your plugins page to see if it shows as active.\n";
                        } else {
                            echo "FAILED! Plugin not in list after update.\n";
                            echo "This suggests object caching is reverting the change.\n";
                        }
                    }
                } else {
                    echo "UPDATE query failed: " . $mysqli->error . "\n";
                }
            } else {
                echo "Plugin is already active in database.\n";
                echo "If it shows as inactive in admin, the issue is:\n";
                echo "  1. Object cache serving stale data\n";
                echo "  2. Old MU-loader filtering the plugin\n";
            }

            $mysqli->close();
        }
    } else {
        echo "Could not extract DB credentials from wp-config.php\n";
    }
} else {
    echo "wp-config.php not found\n";
}
echo "\n";

// Summary
echo "=== SUMMARY ===\n";
echo "If the plugin shows as inactive but the database shows it's active:\n";
echo "  1. Check for an OLD mu-loader at: {$mu_plugins_dir}/tcwp-mu-loader.php\n";
echo "  2. Delete the old mu-loader and upload the new one from mu-loader/tcwp-mu-loader.php\n";
echo "  3. Clear any object cache (Redis/Memcached)\n";
echo "\n";
echo "DELETE THIS FILE AFTER DEBUGGING!\n";
