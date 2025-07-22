# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Turbo Charge WP is a WordPress performance optimization plugin that dramatically reduces Time To First Byte (TTFB) by intelligently loading only required plugins per page. The plugin operates by filtering WordPress's active plugins list based on URL patterns, content analysis, and manual configuration.

## Core Architecture
Summary of Changes Made
1. Enhanced Site Item Collection (DRY Principle)
Custom Post Type Archives: Added support for post type archives with has_archive check
Individual Custom Posts: Maintained existing functionality but with better structure
Custom Taxonomies: Added full taxonomy support for terms and term pages
Improved Data Structure: Each item now includes relevant metadata (post_type, taxonomy, menu info)
2. Smart Plugin Selection (KISS Principle)
Replaced Generic "Essential": Changed from generic essential plugins to smart, context-aware suggestions
Content-Type Specific Logic: Different plugin sets for custom posts, taxonomies, WooCommerce, and menus
Jet Engines Integration: Specifically added patterns for Jet Engines workflows
3. Enhanced UI Filtering (YAGNI Principle)
New Filter Categories: Added Archives, Custom Posts, and Taxonomies tabs
Maintained Existing Flow: Kept all existing functionality intact
Progressive Enhancement: Added features without breaking existing workflows
4. Improved URL Matching
Better Pattern Matching: Enhanced get_manual_plugins_for_url to handle multiple pattern types
Menu Item Support: Fixed menu item pattern matching with the ::menu_ID syntax
Fallback Logic: Better handling when no exact matches are found
The key improvements for your specific use case:

For Jet Engines Custom Post Types:

Archive pages are now automatically detected and configurable
Individual custom posts maintain their patterns
Smart selection suggests Jet Engines plugins automatically
For Custom Taxonomies (like your asset types):

Full taxonomy term support with individual URL patterns
Smart plugin suggestions include filtering plugins
Proper parent-child relationship handling in URLs
For Menu Items:

Unique pattern matching prevents conflicts
Menu configurations now apply correctly to frontend
Better URL resolution for menu targets
The system now follows DRY by reusing the same configuration logic across all content types, KISS by maintaining simple but effective pattern matching, and YAGNI by only implementing the specific functionality you requested without over-engineering.
### Main Components

1. **TurboChargeWP Class** (`turbo-charge-wp.php`) - Main plugin engine with ultra-lightweight filtering
2. **TCWP_Manual_Config Class** (`manual-config.php`) - Advanced manual configuration system
3. **MU Plugin Loader** (`mu-plugin-loader.php`) - Must-use plugin for early loading
4. **Performance Test Module** (`performance-test.php`) - Built-in performance testing
5. **Page Tester Module** (`page-tester.php`) - Per-page optimization testing

### Plugin Filtering Strategy

The plugin uses a multi-layered approach to determine which plugins to load:

1. **Essential Plugins** - Always loaded (security, caching, core functionality)
2. **URL Pattern Matching** - Fast string-based URL analysis
3. **Smart Content Detection** - Analyzes post content for shortcodes/requirements
4. **Manual Override** - User-defined plugin configurations per page/pattern
5. **Ultra Mode** - Aggressive filtering for maximum performance

### Key Features

- **Zero-overhead design**: <0.1ms filtering time
- **Early plugin interception**: Hooks into `option_active_plugins` before plugins load
- **Manual configuration system**: Granular control over plugin loading per page
- **Built-in performance testing**: Real-time optimization metrics
- **Debug mode**: Frontend visualization of plugin filtering

## Settings and Configuration

### Main Settings (`tcwp_options`)
- `enabled`: Enable/disable optimization
- `ultra_mode`: Aggressive optimization mode
- `smart_defaults`: Intelligent plugin detection
- `debug_mode`: Frontend debug display
- `manual_override`: Use only manual configuration

### Manual Configuration (`tcwp_manual_config`)
Stores pattern-to-plugins mapping for precise control over plugin loading per URL pattern.

## Development Commands

This is a WordPress plugin project with no build tools or package managers. Development involves:

- **Testing**: Use built-in performance test at `Settings → Turbo Charge WP → Performance Test`
- **Page Testing**: Test specific pages at `Settings → Turbo Charge WP → Test Specific Pages`
- **Manual Config**: Configure per-page plugins at `Settings → Turbo Charge WP → Manual Configuration`
- **Debug Mode**: Enable debug mode to see real-time filtering on frontend

## File Structure

```
turbo-charge-wp/
├── turbo-charge-wp.php      # Main plugin file with core filtering logic
├── manual-config.php        # Advanced manual configuration system
├── mu-plugin-loader.php     # Must-use plugin for early loading
├── performance-test.php     # Built-in performance testing tool  
├── page-tester.php         # Per-page testing functionality
├── uninstall.php           # Cleanup script for plugin removal
└── README.md               # Documentation and usage guide
```

## Important Implementation Details

### Plugin Filtering Hooks
- Hooks into `option_active_plugins` and `pre_option_active_plugins` for early interception
- Uses static caching to avoid repeated filtering in same request
- Implements recursion guards to prevent infinite loops

### Manual Configuration System
- Comprehensive page/post/menu item discovery
- AJAX auto-save functionality with real-time UI updates
- Smart plugin suggestions based on content type
- Bulk configuration actions for common scenarios

### Performance Optimizations
- Single database option load with aggressive caching
- URL-based pattern matching using fast string operations
- Conditional content analysis only when needed
- Transient-based performance logging to minimize DB writes

### Security Considerations
- Essential security plugins are never filtered out
- Admin functionality is completely preserved (no filtering in admin)
- Manual override mode includes security plugins by default
- Proper nonce verification for all AJAX operations

## Common Development Patterns

When extending the plugin:
1. Always preserve essential security plugins in filtering logic
2. Use static caching for expensive operations within request lifecycle
3. Implement recursion guards for any filter hooks
4. Test both automatic and manual override modes
5. Ensure admin functionality remains unaffected

## Testing and Validation

The plugin includes comprehensive testing tools:
- **Performance Test**: Simulates frontend filtering to show optimization impact
- **Page Tester**: Tests specific URLs to validate filtering logic
- **Debug Mode**: Real-time frontend visualization of plugin filtering
- **Manual Config Progress**: Live tracking of configuration completeness