<?php
if (!defined('FG_TEST_DISPOSABLE') || FG_TEST_DISPOSABLE !== true) { throw new RuntimeException('Disposable install required.'); }
if (!defined('ABSPATH')) { require '/wordpress/wp-load.php'; }
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if (function_exists('WC')) { throw new RuntimeException('WooCommerce must be inactive for this test.'); }
activate_plugin('jalin-mcp-gateway/jalin-mcp-gateway.php');
require_once FG_DIR . 'includes/class-settings.php';
require_once FG_DIR . 'includes/class-finance-admin.php';
$admin = get_users(['role' => 'administrator', 'number' => 1])[0];
$admin->add_cap('manage_woocommerce'); wp_set_current_user(0); wp_set_current_user($admin->ID);
$permission = FG_Finance_Admin::permission();
if (!is_wp_error($permission) || $permission->get_error_code() !== 'woocommerce_inactive') { throw new RuntimeException('Inactive WooCommerce not detected.'); }
ob_start(); FG_Finance_Admin::render(FG_Core::settings()); $html = ob_get_clean();
if (strpos($html, 'Finance Setup Unavailable') === false || strpos($html, 'Activate WooCommerce') === false || strpos($html, 'fg_finance_save') !== false) { throw new RuntimeException('Inactive finance UI did not render safely.'); }
if (FG_Finance_Admin::gateways(['legacy' => []]) !== ['legacy' => ['name' => 'legacy', 'registered' => false, 'enabled' => false]]) { throw new RuntimeException('Saved gateway listing failed without WooCommerce.'); }
echo wp_json_encode(['passed' => 3, 'total' => 3, 'woocommerce_active' => false]);
