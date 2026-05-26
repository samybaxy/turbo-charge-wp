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
     * Script handles that must NOT be deferred. Deferring these would break
     * scripts that inline document.write, execute before DOMContentLoaded,
     * or are depended on by synchronous inline code in the theme.
     *
     * jquery/jquery-core/jquery-migrate are kept blocking because themes
     * commonly use inline <script>jQuery(...) blocks that assume jQuery is
     * available synchronously. wp-embed uses document.write-style injection.
     */
    private static $defer_blocklist = [
        // jQuery — themes use inline jQuery(...) blocks that need it synchronous.
        'jquery',
        'jquery-core',
        'jquery-migrate',
        // WordPress core — synchronous by design or uses document.write.
        'wp-embed',
        'wp-tinymce',
        'wp-tinymce-root',
        'wp-tinymce-inline',
        'underscore',
        'backbone',
        // Elementor — bootstraps widgets and frontend JS synchronously.
        'elementor-frontend',
        'elementor-pro-frontend',
        'elementor-frontend-modules',
        'elementor-waypoints',
        'elementor-sticky',
        // WooCommerce — cart fragments, checkout validation need synchronous load.
        'woocommerce',
        'wc-cart-fragments',
        'wc-add-to-cart',
        'wc-checkout',
        'wc-address-i18n',
        'woocommerce-general',
        // FunnelKit / CartFlows — order bump and checkout JS must be synchronous.
        'wfacp-front',
        'wffn-front',
        'cartflows-front',
        'fkcart',
        // AffiliateWP — tracks referrals on page load.
        'affiliate-wp-tracking',
        // NitroPack — must not be deferred as it orchestrates other optimizations.
        'nitropack-main',
    ];

    /**
     * Register frontend hooks. Called from SHYPDR_Main::setup_frontend_hooks()
     * only when shypdr_frontend_optimizations is enabled.
     */
    public static function init() {
        // Plugin-aware resource hints — runs once per response.
        add_filter('wp_resource_hints', [__CLASS__, 'add_resource_hints'], 10, 2);

        // JS defer — mark non-critical scripts so WP emits defer attribute.
        // Priority 9999 ensures all plugins have enqueued before we walk the list.
        add_action('wp_enqueue_scripts', [__CLASS__, 'defer_non_critical_scripts'], 9999);

        // Pre-cache hardening.
        self::slow_heartbeat();
        self::disable_emoji();
    }

    /**
     * Apply defer strategy to all enqueued scripts that aren't in the
     * blocklist and haven't already been given an explicit strategy.
     *
     * Uses wp_script_add_data() (WP 4.2+, strategy key WP 6.3+). On older
     * WP the 'strategy' key is simply ignored — no harm done.
     *
     * Only touches scripts in the footer (in_footer = true). Scripts in
     * <head> that lack an explicit strategy are left untouched because
     * deferring a head script while its dependents are in the footer can
     * create ordering races.
     */
    public static function defer_non_critical_scripts() {
        global $wp_scripts;
        if (!($wp_scripts instanceof WP_Scripts)) {
            return;
        }

        $blocklist = array_flip(self::$defer_blocklist);

        // Build a set of handles that are loaded in <head> (not in_footer).
        // We must never defer a footer script whose dep chain includes a head
        // script — the head script executes synchronously before the deferred
        // one downloads, so order is already correct, but deferring the child
        // can create a race if the dep is also deferred later.
        $head_handles = [];
        foreach ($wp_scripts->registered as $handle => $script) {
            if (empty($script->extra['group']) && !in_array($handle, $wp_scripts->in_footer, true)) {
                $head_handles[$handle] = true;
            }
        }

        foreach ($wp_scripts->queue as $handle) {
            if (isset($blocklist[$handle])) {
                continue;
            }

            $registered = $wp_scripts->registered[$handle] ?? null;
            if (!$registered) {
                continue;
            }

            // Never defer scripts that load in <head>.
            if (isset($head_handles[$handle])) {
                continue;
            }

            $extra = $registered->extra ?? [];

            // Don't override a strategy already set by the plugin that registered it.
            if (!empty($extra['strategy'])) {
                continue;
            }

            // Skip scripts with any inline before/after code — inline code
            // commonly assumes the script has already executed synchronously.
            if (!empty($extra['before']) || !empty($extra['after'])) {
                continue;
            }

            // Skip if any direct dependency is a head (blocking) script.
            // Deferring a footer script while its dep runs synchronously in
            // <head> is safe in theory, but some scripts re-declare globals
            // inside closures that the dep expects to find immediately.
            if (!empty($registered->deps)) {
                $has_head_dep = false;
                foreach ($registered->deps as $dep) {
                    if (isset($head_handles[$dep]) || isset($blocklist[$dep])) {
                        $has_head_dep = true;
                        break;
                    }
                }
                if ($has_head_dep) {
                    continue;
                }
            }

            wp_script_add_data($handle, 'strategy', 'defer');
        }
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
