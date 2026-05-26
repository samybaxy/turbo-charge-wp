<?php
/**
 * Frontend Asset Optimizer for Samybaxy's Hyperdrive
 *
 * Phase 2 features that COMPLEMENT (rather than duplicate) a page-cache
 * plugin like NitroPack / WP Rocket / LiteSpeed:
 *
 * - Plugin-aware resource hints — preconnect/dns-prefetch only for the
 *   third-party origins that are actually needed on the current page.
 *   Other tools add generic hints to every page; we use Hyperdrive's
 *   per-page plugin set to be selective.
 *
 * - Pre-cache hardening — slow the WordPress Heartbeat and remove the
 *   emoji detection script before NitroPack snapshots the HTML. The
 *   resulting lighter HTML is cached once and served forever.
 *
 * Everything is gated by the shypdr_frontend_optimizations toggle and
 * runs on the frontend only. Admin pages, REST, AJAX, and CRON are
 * untouched.
 *
 * Note: a blanket JS-defer feature was removed in 6.1.5 — too many
 * third-party plugins (Elementor, WooCommerce checkout, FunnelKit)
 * register inline code that requires synchronous script execution.
 * Let the dedicated page-cache plugin (NitroPack/WP Rocket/LiteSpeed)
 * handle JS deferral with its own dependency-aware optimizer.
 *
 * @package SamybaxyHyperdrive
 * @since 6.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SHYPDR_Asset_Optimizer {

    /**
     * Plugin slug → list of third-party origins that benefit from a
     * preconnect hint. Origins are added ONLY when the plugin actually
     * loaded for the current request (i.e. it's in needed_plugins or
     * present in active_plugins as a fallback).
     */
    private static $origin_map = [
        'woocommerce-gateway-stripe' => ['https://js.stripe.com', 'https://m.stripe.network'],
        'woocommerce-stripe-gateway' => ['https://js.stripe.com', 'https://m.stripe.network'],
        'stripe-for-woocommerce'     => ['https://js.stripe.com'],
        'woocommerce-paypal-payments' => ['https://www.paypal.com', 'https://www.paypalobjects.com'],
        'woocommerce-payments'       => ['https://js.stripe.com'],
        'paystack'                   => ['https://js.paystack.co'],
        'woo-paystack'               => ['https://js.paystack.co'],
        'presto-player'              => ['https://player.vimeo.com', 'https://i.vimeocdn.com'],
        'presto-player-pro'          => ['https://player.vimeo.com', 'https://i.vimeocdn.com'],
        'embedpress'                 => ['https://www.youtube.com', 'https://i.ytimg.com'],
    ];

    /**
     * Register frontend hooks. Called from SHYPDR_Main::setup_frontend_hooks()
     * only when shypdr_frontend_optimizations is enabled.
     */
    public static function init() {
        // Plugin-aware resource hints — runs once per response.
        add_filter('wp_resource_hints', [__CLASS__, 'add_resource_hints'], 10, 2);

        // Pre-cache hardening.
        self::slow_heartbeat();
        self::disable_emoji();
    }

    /**
     * Inject preconnect hints for third-party origins that the current
     * page actually needs. NitroPack will cache the resulting HTML
     * (including these hints) so this work amortizes across visitors.
     *
     * @param array  $hints         Existing hints.
     * @param string $relation_type 'preconnect' | 'dns-prefetch' | etc.
     * @return array Augmented hints.
     */
    public static function add_resource_hints($hints, $relation_type) {
        if ('preconnect' !== $relation_type) {
            return $hints;
        }

        $loaded_slugs = self::get_loaded_plugin_slugs();
        if (empty($loaded_slugs)) {
            return $hints;
        }

        foreach (self::$origin_map as $slug => $origins) {
            if (!in_array($slug, $loaded_slugs, true)) {
                continue;
            }
            foreach ($origins as $origin) {
                $hints[] = [
                    'href'        => $origin,
                    'crossorigin' => 'anonymous',
                ];
            }
        }

        return $hints;
    }

    /**
     * Build the set of plugin slugs active on the current request.
     *
     * Prefers Hyperdrive's MU-loader filter data (post-filtering, so it
     * reflects what ACTUALLY loaded after restriction), and falls back
     * to active_plugins when Hyperdrive itself is disabled.
     *
     * @return string[]
     */
    private static function get_loaded_plugin_slugs() {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $data = isset($GLOBALS['shypdr_mu_filter_data']) ? $GLOBALS['shypdr_mu_filter_data'] : null;

        if (is_array($data) && !empty($data['loaded_plugins'])) {
            $cached = array_map(function ($plugin_path) {
                $pos = strpos($plugin_path, '/');
                return $pos !== false ? substr($plugin_path, 0, $pos) : $plugin_path;
            }, $data['loaded_plugins']);
            return $cached;
        }

        $active = (array) get_option('active_plugins', []);
        $cached = array_map(function ($plugin_path) {
            $pos = strpos($plugin_path, '/');
            return $pos !== false ? substr($plugin_path, 0, $pos) : $plugin_path;
        }, $active);

        return $cached;
    }

    /**
     * Drop the WordPress Heartbeat from 15s → 60s on the frontend. The
     * admin / post-edit Heartbeat is untouched.
     */
    private static function slow_heartbeat() {
        add_filter('heartbeat_settings', function ($settings) {
            $settings['interval'] = 60;
            return $settings;
        });
    }

    /**
     * Strip the WordPress emoji detection script + stylesheet from the
     * frontend. Saves ~10 KB of payload and one render-blocking script
     * lookup that almost no modern visitor needs.
     */
    private static function disable_emoji() {
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_filter('the_content_feed', 'wp_staticize_emoji');
        remove_filter('comment_text_rss', 'wp_staticize_emoji');
        remove_filter('wp_mail', 'wp_staticize_emoji_for_email');

        // Also drop the matching DNS-prefetch hint that WP adds for s.w.org.
        add_filter('emoji_svg_url', '__return_false');
    }
}
