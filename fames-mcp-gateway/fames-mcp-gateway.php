<?php
/**
 * Plugin Name: JalinWP
 * Description: An open-source MCP gateway for WordPress and WooCommerce with Read Only, Reviewed Changes, and YOLO modes.
 * Version: 0.3.3
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: Team Fames
 * License: GPL-2.0-or-later
 * Text Domain: fames-mcp-gateway
 *
 * The legacy plugin basename and text domain are retained for in-place upgrades.
 */
defined('ABSPATH') || exit;
define('FG_VERSION', '0.3.3');
define('FG_FILE', __FILE__);
define('FG_DIR', __DIR__ . '/');

foreach (['core', 'settings', 'tools', 'approvals', 'auth', 'oauth', 'discovery', 'connection', 'connection-trace', 'server', 'admin', 'wp-tools', 'wc-tools', 'analytics', 'finance-admin', 'design-blocks', 'page-layouts', 'page-design', 'design-review'] as $fg_module) {
    require_once FG_DIR . 'includes/class-' . $fg_module . '.php';
}
register_activation_hook(__FILE__, ['FG_Core', 'activate']);
register_deactivation_hook(__FILE__, ['FG_Core', 'deactivate']);
FG_Auth::boot();
FG_OAuth::boot();
FG_Discovery::boot();
FG_Connection_Trace::boot();
FG_Admin::boot();
FG_Finance_Admin::boot();
FG_Page_Layouts::boot();
FG_Page_Design::boot();
FG_Design_Review::boot();
add_action('plugins_loaded', ['FG_Core', 'maybe_upgrade'], 20);
add_action('fg_daily_cleanup', ['FG_Core', 'cleanup']);
add_action('rest_api_init', static function () {
    FG_WP_Tools::register();
    FG_WC_Tools::register();
    FG_Analytics::register();
    FG_Page_Design::register();
    FG_OAuth::register();
    FG_Server::register();
}, 20);
// The plugin uses WooCommerce REST controllers, never the orders post table.
add_action('before_woocommerce_init', static function () {
    if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', FG_FILE, true);
    }
});
