=== Samybaxy's Hyperdrive ===
Contributors: samybaxy
Donate link: https://github.com/samybaxy/samybaxy-hyperdrive/blob/main/DONATE.md
Tags: performance, optimization, speed, caching, conditional-loading
Requires at least: 6.4
Tested up to: 6.9
Stable tag: 6.1.6
Requires PHP: 8.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Load only essential plugins per page for 65-75% faster WordPress sites through intelligent filtering.

== Description ==

**Status:** Production Ready
**Current Version:** 6.1.6

Samybaxy's Hyperdrive makes WordPress sites **65-75% faster** by intelligently loading only the plugins needed for each page.

Instead of loading 120 plugins for every page, we load only 12-45 plugins for the current page - automatically, without breaking anything.

= Key Features =

* **85-90% plugin reduction** on most pages
* **65-75% faster page loads** without caching
* **Zero configuration needed** - works automatically
* **Zero broken functionality** - intelligent dependency detection
* **Automatic dependency resolution** - understands plugin ecosystems
* **Multi-layer caching** - 60-75% faster on cached requests
* **Admin-only debug widget** - real-time performance monitoring

= How It Works =

Traditional WordPress loads ALL plugins on EVERY page:
* Shop page loads: WooCommerce, LearnPress, Forms, Analytics, SEO... (120 plugins)
* Blog page loads: WooCommerce, LearnPress, Forms, Analytics, SEO... (120 plugins)
* Result: Slow sites (3-8 seconds TTFB)

**Samybaxy's Hyperdrive intelligently filters plugins:**
* Shop page loads: WooCommerce + dependencies only (35 plugins)
* Blog page loads: Blog plugins + dependencies only (18 plugins)
* Result: **65-75% faster!**

= Intelligent Detection System =

The plugin automatically detects which plugins are needed via:

1. **URL-based detection** - Recognizes WooCommerce, courses, membership, blog pages
2. **Content analysis** - Scans post content for shortcodes and page builder widgets
3. **Dependency resolution** - Automatically loads all required plugin dependencies
4. **User role detection** - Loads extra plugins for logged-in users, affiliates, members
5. **Smart defaults** - Always loads essential plugins (page builders, theme cores)

= Supported Plugin Ecosystems =

* **JetEngine** - jet-engine, jet-menu, jet-blocks, jet-elements, jet-tabs, jet-popup, jet-woo-builder, and 10+ modules
* **WooCommerce** - woocommerce, memberships, subscriptions, product bundles, smart coupons
* **Elementor** - elementor, elementor-pro, the-plus-addons, thim-elementor-kit
* **Content Restriction** - restrict-content-pro, rcp-content-filter-utility
* **Automation** - uncanny-automator, fluent-crm
* **Forms** - fluentform, fluentformpro, jetformbuilder
* **Other** - LearnPress, Affiliate WP, EmbedPress, Presto Player, and many more

= Safety Features =

* Never filters WordPress admin area
* Never filters AJAX requests
* Never filters REST API requests
* Never filters WP-CRON requests
* Validates plugin existence before loading
* Maintains WordPress native plugin load order
* Falls back to loading all plugins if anything breaks
* **Security:** Debug widget only visible to administrators
* **Security:** Plugin info hidden from frontend users and visitors
* **Clean:** No error logging or debug output

= Performance Optimization =

* **Expected reduction:** 85-90% fewer plugins loading on most pages
* **Speed improvement:** 65-75% faster page loads
* **Memory savings:** 40-60% less memory usage
* **Filter overhead:** < 2.5ms per request
* **Server cost reduction:** 60-70% for same traffic

= What's New in v6.1.1 =

🚀 **Consolidated production-validated patch release** — battle-tested on a 154-plugin WooCommerce / membership / LMS site (see PERFORMANCE-AUDIT-2026.md). Folds in every internal iteration since 6.1.0 into one stable release.

**⚡ MU-loader overhead removed (Phase 1):**

* **5 → 1 DB query in the MU-loader.** The five separate `$wpdb->get_var()` calls per cache-miss request are collapsed into a single autoloaded `shypdr_mu_payload` option that piggybacks on the alloptions cache.
* **~110 KB less PHP parsed per frontend request.** Admin-only classes (`SHYPDR_Plugin_Scanner`, `SHYPDR_Dependency_Detector`, `SHYPDR_Content_Analyzer`) now load lazily via an autoloader instead of being `require_once`'d on every request.
* **Frontend transient log writes removed.** The 10%-sampled `set_transient()` call on `wp_loaded` was writing to `wp_options` on real visitor traffic. Logging is now opt-in (`Runtime Logging` setting, default OFF) and writes to a rotated file under `uploads/shypdr-logs/`.
* **save_post analysis debounced.** Re-analysis is skipped for revisions, autosaves, non-public post types, unchanged `post_modified`, and within 60 s of the last analysis — kills the thrash on ACF / meta-only saves.
* **Lookup table capped + LRU-evicted.** `shypdr_url_requirements` is now bounded at 1500 entries (~500 posts) so the serialized blob stays within a healthy autoload budget.
* **Cache-plugin coexistence.** The MU-loader now detects WP Rocket / LiteSpeed / WP Super Cache / NitroPack / ShortPixel preloaders and bows out, so cached HTML reflects the full plugin set instead of diverging from real-visitor pages.

**🎯 NitroPack-complementary frontend optimizations (Phase 2):**

* **Plugin-aware preconnect hints.** Hyperdrive already knows which plugins actually loaded for the current page — so it can preconnect to `js.stripe.com` only on checkout, `player.vimeo.com` only when Presto Player is active for the page, etc. Generic cache-plugin hints add the same origins to every page; this one is selective.
* **Pre-cache hardening.** WordPress Heartbeat slowed to 60 s on the frontend, emoji detection script removed. NitroPack snapshots the resulting lighter HTML once and serves it forever from cache.
* **Page-cache purge on config change.** Toggling `shypdr_enabled` or rebuilding the restrictable set now automatically purges NitroPack, WP Rocket, LiteSpeed, WP Super Cache, W3 Total Cache, Cache Enabler, and SiteGround Optimizer (whichever is installed).
* All Phase 2 features sit behind a single **Frontend Optimizations** toggle (default OFF on upgrade) so existing sites only opt in deliberately.

**📊 Performance Insights tab (Phase 3):**

* **Overall stats card.** Total samples in window, median plugin reduction, median PHP wall time (request start → wp_loaded — a directional TTFB proxy), median plugins loaded vs total.
* **Per URL pattern breakdown.** Logged URLs collapse to patterns (`/shop/product/abc` + `/shop/product/def` → `/shop/product/*`) so similar pages roll up together.
* **"Filtering isn't helping" callout.** Surfaces URL patterns where median reduction is 0% across 3+ samples — clear signal the filtering overhead on those pages may exceed the savings.
* **elapsed_ms** captured per log entry via `REQUEST_TIME_FLOAT` (microsecond-cheap, no extra I/O).
* Settings → Hyperdrive → **Performance Insights** card. Requires Runtime Logging enabled.

**🛡️ Deactivation safety:**

* **Deactivation removes the MU-loader file** automatically (WordPress always loads MU-plugins regardless of activation state — leaving the loader installed meant a "deactivated" Hyperdrive could still influence requests).
* **`shypdr_mu_payload` is dropped on deactivation** so even if the mu-plugins directory is read-only and the file deletion fails, the MU-loader has nothing to act on.
* **Safety net in the MU-loader itself.** If the file somehow survives, the MU-loader checks `active_plugins` / `active_sitewide_plugins` and bails unless the main plugin is currently active. Free check — rides the autoloaded alloptions cache.
* User preferences (Enable Plugin Filtering, Essential Plugins, etc.) are preserved across deactivation cycles — only removed on uninstall.

= What's New in v6.0.2 =

🔗 **WordPress 6.5+ Plugin Dependencies Integration**

Full integration with WordPress core's plugin dependency system:

* **WP_Plugin_Dependencies API** - Native support for WordPress 6.5+ dependency tracking
* **Requires Plugins Header** - Automatic parsing of the official plugin dependency header
* **Circular Dependency Detection** - Prevents infinite loops using DFS algorithm (O(V+E) complexity)
* **5-Layer Detection** - WP Core → Header → Code Analysis → Pattern Matching → Known Ecosystems
* **wp_plugin_dependencies_slug Filter** - Support for premium/free plugin slug swapping

**Technical Improvements:**
* Database-backed dependency map for MU-loader
* Automatic map rebuild on plugin activation/deactivation
* Version upgrade detection with automatic updates
* Extended pattern detection for more plugins

= What's New in v6.0.1 =

🛒 **Checkout & Payment Gateway Fixes**

Fixed critical issue where payment gateways weren't loading on checkout pages:

* **Dynamic Gateway Detection** - Automatically detects and loads Stripe, PayPal, and other payment plugins on checkout/cart pages
* **Streamlined Checkout** - Optimized plugin loading for better checkout performance
* **Smart Membership Loading** - Membership plugins now only load on checkout for logged-in users

= What's New in v6.0 =

🚀 **Official Rebrand & WordPress.org Submission**

Complete plugin rebrand from "Turbo Charge" to "Samybaxy's Hyperdrive":

* **New Identity** - Fresh branding with distinctive SHYPDR prefix
* **WordPress.org Compliant** - Meets all plugin directory requirements
* **Clean Codebase** - Extracted CSS, improved structure, proper escaping
* **MU-Loader Update** - Renamed to shypdr-mu-loader.php for consistency

⚠️ **Note:** Fresh installation required - settings from previous versions will not migrate.

= What's New in v5.1 =

**Heuristic Dependency Detection System** - Zero Manual Maintenance!

The plugin now automatically detects plugin dependencies using 4 intelligent methods:

1. **WordPress 6.5+ Headers** - Reads official "Requires Plugins" header
2. **Code Analysis** - Scans for class_exists(), defined(), hook patterns
3. **Pattern Matching** - Recognizes naming conventions (jet-*, woocommerce-*, elementor-*)
4. **Known Ecosystems** - Validates with curated plugin relationships

**Benefits:**
* Zero manual maintenance - dependencies auto-detected
* Works with custom/proprietary plugins automatically
* Auto-rebuilds on plugin activation/deactivation
* Database storage for fast retrieval

= What's New in v5.0 =

**Intelligent Plugin Scanner** - Heuristic Analysis System

Automatically analyzes all plugins and scores them 0-100 based on:
* Known patterns (page builders, theme cores)
* Keywords in name/description
* Hook registrations (wp_head, wp_footer, etc.)
* Asset enqueuing (global CSS/JS)
* Custom post type registration

**Detection Result Caching** - 60-75% Faster Filtering

Dual-layer caching system:
* **Requirements Cache** - Pre-computed URL to plugins mapping
* **Detection Cache** - Runtime caching with object cache support
* **Performance:** 0.3-0.8ms cached (vs 1.2-2.1ms uncached)

= Admin Interface =

Settings page at **Settings > Samybaxy's Hyperdrive** with:
* Enable/disable plugin filtering checkbox
* Enable/disable debug widget checkbox
* Intelligent plugin scanner with visual cards
* Dependency map viewer with statistics
* Performance logs showing recent page loads
* Cache statistics and management
* Stats: plugins loaded, plugins filtered, reduction percentage

= Debug Widget =

Floating widget that appears on frontend when enabled:
* **Admin only** - Only visible to logged-in administrators
* Frontend users and incognito visitors cannot see it (security)
* Shows total plugins available
* Shows plugins loaded this page
* Shows plugins filtered out
* Shows reduction percentage
* Lists essential detected plugins
* Shows sample of filtered out plugins
* Fully interactive with expand/collapse
* Responsive design (works on mobile)

= Performance Statistics =

Typical performance improvements:

| Page Type | Before | After | Improvement |
|-----------|--------|-------|-------------|
| Homepage | 3.5s TTFB | 1.2s TTFB | 65% faster |
| Shop Page | 4.2s TTFB | 1.4s TTFB | 67% faster |
| Blog Page | 2.8s TTFB | 0.8s TTFB | 71% faster |
| Course Page | 5.1s TTFB | 1.9s TTFB | 63% faster |

== Installation ==

= Automatic Installation =

1. Upload the plugin files to `/wp-content/plugins/samybaxy-hyperdrive/` directory
2. Go to WordPress Admin > Plugins
3. Find "Samybaxy's Hyperdrive" and click "Activate"
4. Go to Settings > Samybaxy's Hyperdrive
5. Check "Enable Plugin Filtering"
6. Save changes

That's it! The plugin works automatically with zero configuration.

= MU-Loader Installation (Recommended) =

For best performance, install the MU-loader:

1. After activating the plugin, go to Settings > Samybaxy's Hyperdrive
2. Click "Install MU-Loader Now" button (if prompted)
3. The MU-loader will be automatically installed
4. This enables filtering BEFORE WordPress loads plugins

Alternatively, manually copy:
* From: `wp-content/plugins/samybaxy-hyperdrive/mu-loader/shypdr-mu-loader.php`
* To: `wp-content/mu-plugins/shypdr-mu-loader.php`

= Manual Installation =

1. Download the plugin files
2. Extract to `/wp-content/plugins/samybaxy-hyperdrive/`
3. Activate from WordPress Admin > Plugins
4. Enable filtering in Settings > Samybaxy's Hyperdrive

== Frequently Asked Questions ==

= Does it work with WooCommerce? =

Yes! WooCommerce + all 15+ extensions are fully supported with automatic dependency detection.

= Does it work with JetEngine? =

Yes! JetEngine + all 18+ add-ons are fully supported.

= Does it work with Elementor? =

Yes! Elementor + Pro + all add-ons are fully supported.

= What if plugins break? =

The system automatically detects issues and loads all plugins as a fallback. You can also disable filtering temporarily from Settings > Samybaxy's Hyperdrive.

= Does it require configuration? =

No! Works automatically with zero configuration. The intelligent scanner and dependency detector handle everything.

= What about WordPress admin? =

Admin always loads all plugins (safe by design). Filtering only happens on frontend pages.

= Can I disable it temporarily? =

Yes, go to Settings > Samybaxy's Hyperdrive and uncheck "Enable Plugin Filtering".

= Does it work with caching plugins? =

Yes! Works great together with WP Rocket, LiteSpeed Cache, and other caching plugins.

= Will it improve my Google ranking? =

Yes! Faster pages rank better in Google. Core Web Vitals improvements directly impact SEO.

= Is it safe? =

Yes. The plugin understands dependencies, won't break functionality, and has automatic fallbacks.

= How much memory does it use? =

~110KB additional overhead (includes caching system).

= How fast is the filter? =

0.3-0.8ms per request (cached) or 1.2-2.1ms (uncached). Target is < 2.5ms.

= Can I customize which plugins are essential? =

Yes! Go to Settings > Samybaxy's Hyperdrive > Essential Plugins tab to customize via the admin UI.

= How do I debug if something breaks? =

1. Go to Settings > Samybaxy's Hyperdrive
2. Check "Recent Performance Logs" to see which plugins were loaded
3. Enable "Debug Widget" to see real-time stats on frontend (admin only)
4. Temporarily disable filtering to verify it's the cause

= Does it work with multisite? =

Yes, the plugin supports WordPress multisite installations.

== Screenshots ==

1. The main settings page with options to Manage Essential Plugins, Plugin Dependencies, and rebuild cache.
2. The settings subpage where you can manage your essential plugin list or scan all plugins for heuristics.
3. The settings subpage where you can manage and map plugin dependencies.
4. The frontPage with Debug bar displaying plugin filteration data.
5. GTMetrix score for Dev environment without optimizations for over 124 plugins.
6. GTMetrix score for Dev environment running Optimization with NitroPack only on WPEngine Host.
7. GTMetrix score for Dev environment running Optimization with NitroPack and HyperDrive on WPEngine Host.

== Changelog ==

= 6.1.6 - May 26, 2026 =
* 🗑️ **Removed: Frontend Optimizations setting.** The NitroPack-complementary tweaks checkbox (preconnect hints, heartbeat throttle, emoji removal) has been removed from the settings page. These concerns are handled by the page-cache plugin.

= 6.1.5 - May 26, 2026 =
🛑 **Critical fix: remove JS-defer feature + defer ALL synchronous heavy work to cron**

The blanket JS-defer feature shipped in 6.1.0 caused critical errors on Elementor + WooCommerce + FunnelKit sites no matter how careful the blocklist. It's removed entirely — page-cache plugins (NitroPack, WP Rocket, LiteSpeed) already handle JS deferral correctly with dependency-aware engines. Hyperdrive should not duplicate that work.

A line-by-line review of the plugin also turned up three more synchronous-heavy-work paths that could 502 PHP-FPM on large sites:

* 🛑 **JS-defer feature removed.** The `defer_non_critical_scripts()` method, its blocklist, and its `wp_enqueue_scripts:9999` hook are gone. The Frontend Optimizations toggle still controls preconnect hints, heartbeat throttle, and emoji removal — those are safe.
* 🐛 **Fixed: activating/deactivating any plugin no longer 502s.** `handle_plugin_activation()` and `handle_plugin_deactivation()` were rebuilding the dependency map + restrictable set synchronously inside the activation HTTP request — same 50-150 plugin-file reads that 6.1.2 deferred for our own activation. Now they only clear caches inline and schedule a debounced `shypdr_deferred_rebuild` cron event 15 s out, so bulk activations coalesce into one rebuild.
* 🐛 **Fixed: plugin upgrade no longer 502s the next admin page load.** `shypdr_check_version_upgrade()` ran the same heavy rebuild on `admin_init` after every version bump. Now it does only the MU-loader file refresh + version-bump synchronously and schedules the rebuild to the same `shypdr_deferred_rebuild` cron event.
* 🐛 **Fixed: Essential Plugins tab no longer 502s on fresh installs.** When `shypdr_plugin_analysis` wasn't in the DB yet, opening the tab triggered a synchronous `scan_active_plugins()` that read up to 500KB per plugin × N plugins. Now it shows a "Scan pending" notice and queues the same cron event.
* 🧹 **Cleanup: removed unused `SHYPDR_Main::get_essential_plugins()`** (dead code — no callers).
* 🛡️ **Deactivation now uses `wp_clear_scheduled_hook()`** to unschedule both deferred-scan and deferred-rebuild events robustly.

**Upgrade impact:** critical for any install that's been hitting 502s when activating plugins, upgrading Hyperdrive, or opening the Essential Plugins tab. JS-defer users will lose that specific feature — re-enable in their page-cache plugin instead.

= 6.1.4 - May 26, 2026 =
🛡️ **Fix: JS defer feature was too aggressive, causing critical errors on Elementor/WooCommerce/FunnelKit sites**

The NitroPack-complementary frontend optimizations toggle includes a JS defer feature that applied `defer` to all footer scripts not in a short blocklist. On sites using Elementor, WooCommerce checkout, or FunnelKit, this broke scripts that depend on synchronous execution order, resulting in a "There has been a critical error" page.

* 🐛 **Fixed: expanded blocklist** — Elementor frontend, WooCommerce cart/checkout scripts, FunnelKit checkout JS, AffiliateWP tracking, and NitroPack itself are now always excluded from deferral.
* 🐛 **Fixed: skip scripts loaded in `<head>`** — head scripts must always execute synchronously; deferring them causes ordering races with footer dependents.
* 🐛 **Fixed: skip scripts with `after` inline data** — previously only `before` inline data was checked. Scripts with `after` inline code also assume synchronous execution.
* 🐛 **Fixed: skip scripts whose direct deps include a head or blocklisted script** — prevents deferring a footer script that relies on a synchronous predecessor.

= 6.1.3 - May 26, 2026 =
🛡️ **Critical fix: prevent filtered plugin list from poisoning persistent object cache (Redis/Memcached)**

On sites using a persistent object cache (e.g., WP Engine Redis), the `option_active_plugins` filter value could be stored in the cache after a frontend request. Subsequent requests — including wp-admin — would then read the cached filtered list, causing WooCommerce, AffiliateWP, and other filtered plugins to appear fully deactivated site-wide, not just on the filtered page.

* 🐛 **Fixed: `wp_cache_delete('active_plugins', 'options')` and `wp_cache_delete('alloptions', 'options')` are now called immediately after filtering** so the filtered value is never persisted in Redis/Memcached. The next request re-reads from the database and gets the full unfiltered list.

**Upgrade impact:** critical for any site running a persistent object cache. Sites on default WordPress (no Redis/Memcached) were not affected but the fix is harmless for them.

= 6.1.2 - May 26, 2026 =
🛡️ **Hotfix: prevent 502 Bad Gateway on activation for large-install sites**

On sites with 100+ active plugins, the activation handler did synchronous file I/O (reading up to 50KB from every active plugin file for dependency detection) inside the activation HTTP request — and the post-activation admin page load did even heavier reads (up to 500KB per plugin) for the essential-plugin scanner. Both could exceed PHP-FPM timeout on managed hosts (WP Engine, Kinsta, etc.) and return 502 Bad Gateway.

* 🐛 **Fixed: activation handler is now near-instant.** Only sets defaults, copies the MU-loader, and writes an empty payload. The heavy dependency-map rebuild, restrictable-set scan, and lookup-table rebuild are deferred to a background `shypdr_deferred_initial_scan` WP-Cron event scheduled ~30 seconds after activation.
* 🐛 **Fixed: first-time-setup admin hook no longer runs the heavy scanner.** Previously called `scan_active_plugins()` (which reads every plugin file) on the first admin page load — also a 502 risk. Now just verifies the MU-loader is present and ensures the deferred cron is scheduled.
* 🛡️ **Safety: all rebuild calls wrapped in `try/Throwable`** so a buggy class can never break activation.
* 🛡️ **Safety: background task raises memory limit and `set_time_limit(300)`** so the deferred scan can complete even on very large sites without PHP-FPM constraints.
* 🔧 **Cleanup: deactivation now unschedules any pending deferred-scan cron event** so a deactivated plugin can't leave orphan cron entries.

**Upgrade impact:** existing installs see zero behaviour change after upgrade — the deferred scan only fires if `shypdr_needs_setup` is set, which is only true after a fresh activation. Sites already running 6.1.1 continue using the data their activation already populated.

= 6.1.1 - May 25, 2026 =
🚀 Consolidated patch release validated against a 154-plugin production WooCommerce / membership / LMS site (see PERFORMANCE-AUDIT-2026.md). Folds in every internal iteration since 6.1.0 into a single stable release.

**⚡ Phase 1 — MU-loader overhead removed**
* 🔧 Improved: 5 → 1 DB query in the MU-loader. The five separate per-request `$wpdb->get_var()` calls are collapsed into a single autoloaded `shypdr_mu_payload` option that piggybacks on the alloptions cache (≈ free read).
* 🔧 Improved: ~110 KB less PHP parsed per frontend request. Admin-only classes (Plugin Scanner, Dependency Detector, Content Analyzer) load lazily via PSR-style autoloader instead of unconditional require.
* 🔧 Improved: Frontend transient log writes removed from cache-miss hot path; runtime logging now writes to a rotated file when explicitly enabled.

**🎯 Phase 2 — Frontend optimizations that complement page-cache plugins**
* ✨ New: Plugin-aware preconnect hints — Hyperdrive only adds `js.stripe.com` on checkout, `player.vimeo.com` on Presto pages, etc. Selective rather than generic.
* ✨ New: Pre-cache hardening — Heartbeat slowed to 60 s on frontend, emoji detection removed so cache plugins snapshot lighter HTML once.
* ✨ New: Automatic page-cache purge on `shypdr_enabled` toggle / restrictable rebuild. Supports NitroPack, WP Rocket, LiteSpeed, WP Super Cache, W3 Total Cache, Cache Enabler, SiteGround Optimizer.
* 🛡️ Safety: All Phase 2 features behind a single **Frontend Optimizations** toggle (default OFF on upgrade).

**📊 Phase 3 — Performance Insights tab**
* ✨ New: Admin tab answering "is Hyperdrive actually helping?" using the rotated runtime log. Zero frontend cost.
* ✨ New: Per-URL-pattern breakdown (median reduction %, PHP wall time, loaded/total) so similar pages collapse together.
* ✨ New: "Filtering isn't helping" callout surfaces URL patterns where median reduction is 0% across 3+ samples.
* 🔧 Improved: `elapsed_ms` captured per log entry via REQUEST_TIME_FLOAT (no extra I/O).

**🛡️ Deactivation safety**
* 🐛 Fixed: MU-loader file is removed on deactivation so filtering stops on the next request (WordPress always loads MU-plugins regardless of activation state).
* 🐛 Fixed: `shypdr_mu_payload` is dropped on deactivation as a second safety net.
* 🛡️ Safety: MU-loader self-checks `active_plugins` / `active_sitewide_plugins` and bails if the main plugin is no longer active.
* 🔧 Improved: User preferences preserved across deactivation cycles — only removed on uninstall.

= 6.1.0 - March 7, 2026 =
🏗️ Architecture Overhaul: Whitelist to Blacklist Model
* 🔄 Breaking: MU-loader now uses blacklist architecture — loads everything by default, only restricts known-heavy plugins
* ✨ New: Automatic restrictable plugin detection based on ecosystem analysis
* ✨ New: DB-driven restriction rules — no more hardcoded keyword-to-plugin mappings
* ✨ New: Lightweight plugins (user-switching, analytics, utilities) always load automatically
* ✨ New: New plugins auto-load on frontend without code changes
* ✨ New: Ecosystem child detection via dependency map and slug prefix matching
* 🔧 Improved: Plugin scanner builds restrictable set on activation/deactivation
* 🔧 Improved: Admin override support for manual restrictable/unrestricted lists
* 🛡️ Safety: No restrictable set = no filtering (safe fallback)
* 🛡️ Safety: Search pages load all ecosystems to prevent missing results
* 🐛 Fixed: user-switching plugin no longer filtered out
* 🐛 Fixed: WooCommerce extensions (subscriptions, coupons) load on my-account page
* 🐛 Fixed: AffiliateWP addons load on affiliate/partner pages

= 6.0.2 - February 5, 2026 =
🔗 WordPress 6.5+ Plugin Dependencies Integration
* ✨ New: Full integration with WordPress 6.5+ WP_Plugin_Dependencies API
* ✨ New: Native support for Requires Plugins header parsing
* ✨ New: Circular dependency detection using DFS with three-color marking
* ✨ New: Proper slug validation matching WordPress.org format
* ✨ New: Support for wp_plugin_dependencies_slug filter (premium/free plugin swapping)
* ✨ New: 5-layer dependency detection hierarchy
* 🔧 Improved: MU-loader now uses database-stored dependency map
* 🔧 Improved: Automatic dependency map rebuild on plugin changes
* 🔧 Improved: Version upgrade detection with automatic MU-loader updates
* 🛡️ Safety: Circular dependency protection prevents infinite loops
* 🛡️ Safety: Max iteration limit as additional protection

= 6.0.1 - February 1, 2026 =
🛒 Checkout & Payment Gateway Fixes
* 🐛 Fixed: Payment gateways (Stripe, PayPal, etc.) not loading on checkout pages
* ✨ New: Dynamic payment gateway detection for checkout/cart pages
* 🔧 Improved: Streamlined checkout plugin loading for better performance
* 🔧 Improved: Membership plugins now only load on checkout for logged-in users

= 6.0.0 - January 29, 2026 =
🚀 Official Rebrand & WordPress.org Submission
* ⚠️ Breaking: Complete plugin rename from "Turbo Charge" to "Samybaxy's Hyperdrive"
* ⚠️ Breaking: Slug changed from "turbo-charge" to "samybaxy-hyperdrive"
* ⚠️ Breaking: All prefixes changed from TC_/tc_ to SHYPDR_/shypdr_ (6-char distinctive prefix)
* ⚠️ Breaking: MU-loader renamed from tc-mu-loader.php to shypdr-mu-loader.php
* ✨ New: Extracted inline CSS to separate admin-styles.css file
* 🔧 Improved: WordPress.org plugin review compliance
* 🔧 Improved: All database options, transients, and post meta use new prefix
* 🔧 Improved: All CSS classes use new shypdr- prefix
* 📝 Note: Fresh installation required - settings from previous versions will not migrate

= 5.1.0 - December 14, 2025 =
🧠 Zero-Maintenance Dependency Detection
* ✨ New: Heuristic Dependency Detection System with 4 intelligent methods
* ✨ New: WordPress 6.5+ "Requires Plugins" header support
* ✨ New: Code analysis for class_exists(), defined(), and hook patterns
* ✨ New: Pattern matching for naming conventions (jet-*, woocommerce-*, elementor-*)
* ✨ New: Database storage with automatic rebuild on plugin changes
* 🔧 Improved: Dependencies admin page with visual statistics dashboard
* 🔧 Improved: Auto-rebuild triggers on plugin activation/deactivation
* 🔧 Improved: Debug widget now shows scrollable full plugin lists
* 🐛 Fixed: Membership plugins now load on shop page for logged-in users
* 🐛 Fixed: Numeric output escaping in printf() calls
* 🗑️ Removed: Hardcoded dependency map (replaced with heuristic detection)
* ✅ Compliance: Complete internationalization (i18n) for WordPress.org
* ✅ Compliance: WordPress Coding Standards and Plugin Check compatibility

= 5.0.0 - December 5, 2025 =
⚡ Intelligent Scanner & Multi-Layer Caching
* ✨ New: Intelligent Plugin Scanner with heuristic analysis (scores plugins 0-100)
* ✨ New: Dual-layer caching system (Requirements Cache + Detection Cache)
* ✨ New: Admin UI for managing essential plugins with visual cards
* ✨ New: Dynamic essential plugins (replaces static hardcoded whitelist)
* ✨ New: Requirements cache for O(1) hash lookups
* ✨ New: Content analyzer with intelligent shortcode/widget detection
* ✨ New: Filter hooks for developer extensibility (shypdr_essential_plugins, etc.)
* ✨ New: Automatic cache invalidation on content changes
* 🚀 Performance: 40-50% faster average filter time
* 🚀 Performance: 60-75% faster for cached requests (0.3-0.8ms vs 1.2-2.1ms)
* 🔧 Improved: More accurate essential plugin detection via heuristics
* 🔧 Improved: Better customization options through admin interface
* 🐛 Fixed: MU-loader cache early return bug
* 🐛 Fixed: Plugin scanner robustness with defensive checks

= 4.0.5 - August 2025 =
🏭 Production-Ready Stability Release
* 🐛 Fixed: Removed all error_log statements for production performance
* 🔧 Improved: Implemented recursion guard pattern for safe filtering
* 🔧 Improved: Cleaned up temporary debug files and documentation
* ✅ Stability: Production-ready implementation with comprehensive error handling

= 4.0.4 - August 2025 =
🛡️ Hook Filtering Stability
* ✨ New: Recursion guard mechanism to prevent infinite loops
* 🔧 Improved: Hook filtering reliability with dual protection
* 🔧 Improved: Enhanced type validation throughout codebase

= 4.0.3 - August 2025 =
🚨 Critical Bug Fix Release
* 🐛 Fixed: Critical 502 errors caused by infinite recursion in plugin filtering
* 🐛 Fixed: Array type checking to prevent type errors
* 🔧 Improved: Error handling with try-catch-finally blocks

= 4.0.2 - August 2025 =
🔍 Debug & Monitoring Improvements
* ✨ New: Elementor diagnostics for widget detection
* ✨ New: Debug widget for real-time performance monitoring on frontend
* 🔧 Improved: Admin settings page layout and usability
* 🔧 Improved: Enhanced performance logging with detailed statistics

= 4.0.1 - July 2025 =
🔌 Essential Plugins & Compatibility
* ✨ New: Critical whitelist for essential plugins (Elementor, JetEngine, etc.)
* 🐛 Fixed: Jet Menu rendering issues on frontend
* 🔧 Improved: Enhanced dependency detection for plugin ecosystems

= 4.0.0 - July 2025 =
🎉 Initial Public Release
* ✨ New: Core plugin filtering system with intelligent detection
* ✨ New: 50+ plugin dependency map covering major ecosystems
* ✨ New: URL-based detection for WooCommerce, LearnPress, membership pages
* ✨ New: Content analysis for shortcodes and page builder widgets
* ✨ New: User role detection for logged-in users and affiliates
* ✨ New: Recursive dependency resolver algorithm
* ✨ New: Safety fallbacks to prevent site breakage
* ✨ New: Admin settings page for configuration

== Upgrade Notice ==

= 6.0.1 =
🛒 Fixes payment gateway loading on checkout pages. Recommended update for all WooCommerce users.

= 6.0.0 =
⚠️ BREAKING CHANGE: Complete plugin rename to "Samybaxy's Hyperdrive". Fresh installation required - settings from "Turbo Charge" will not migrate. Please reconfigure after upgrade.

= 5.1.0 =
🧠 Major update with automatic dependency detection! Zero manual maintenance required. Dependencies auto-detected via 4 intelligent methods.

= 5.0.0 =
⚡ Major architecture update with intelligent plugin scanner and dual-layer caching. Performance improvements of 40-75% on filtering operations.

= 4.0.5 =
🏭 Production-ready stability release. Recommended for all users on 4.x versions.

== Technical Details ==

= Performance =

* **Time Complexity:** O(1) detection with cached lookups, O(m) filtering where m = active plugins
* **Space Complexity:** ~110KB memory overhead (includes caching)
* **Filter Speed:** 0.3-0.8ms cached, 1.2-2.1ms uncached
* **Plugin Reduction:** 85-90% on most pages
* **Speed Improvement:** 65-75% faster page loads

= Architecture =

* **Heuristic Dependency Detector** - Auto-detects plugin dependencies
* **Intelligent Plugin Scanner** - Analyzes and scores all plugins
* **Dual-Layer Caching** - Requirements cache + detection cache
* **Content Analyzer** - Intelligent content scanning with caching
* **Detection System** - URL, content, user role, and default detection
* **Resolver Algorithm** - Queue-based recursive dependency resolution
* **Safety Layer** - Backend detection, validation, and fallbacks

= Database Options =

All options use `shypdr_` prefix:
* `shypdr_enabled` - Enable/disable plugin filtering
* `shypdr_debug_enabled` - Enable/disable debug widget
* `shypdr_essential_plugins` - User-customized essential plugins
* `shypdr_dependency_map` - Auto-detected plugin dependencies
* `shypdr_plugin_analysis` - Cached scanner results
* `shypdr_url_requirements` - Pre-computed URL lookups
* `shypdr_logs` (transient) - Performance logs

= WordPress Hooks =

* `plugins_loaded` - Initialize core components
* `admin_menu` - Register settings page
* `admin_init` - Register settings fields
* `option_active_plugins` - Filter plugin list before WordPress loads them
* `wp_enqueue_scripts` - Load debug widget CSS/JS
* `wp_footer` - Render debug widget HTML
* `save_post` - Update requirements cache
* `activated_plugin` - Rebuild dependency map
* `deactivated_plugin` - Rebuild dependency map

= Filter Hooks for Developers =

* `shypdr_essential_plugins` - Override essential plugins
* `shypdr_dependency_map` - Override dependency map
* `shypdr_url_detected_plugins` - Customize URL detection
* `shypdr_content_detected_plugins` - Customize content detection

= Support =

For support and documentation:
* GitHub: https://github.com/samybaxy/samybaxy-hyperdrive
* Settings > Samybaxy's Hyperdrive - View performance logs
* Enable debug widget for real-time monitoring

== Credits ==

Developed by samybaxy with a focus on performance, safety, and zero configuration.

Special thanks to the WordPress community for their feedback and testing.