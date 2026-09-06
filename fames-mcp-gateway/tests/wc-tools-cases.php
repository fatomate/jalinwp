<?php
/** Disposable WordPress + WooCommerce fixture tests; never run on a live site. */
if (!defined('FG_TEST_DISPOSABLE') || FG_TEST_DISPOSABLE !== true) { throw new RuntimeException('Run only through the disposable test harness.'); }
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
activate_plugin('woocommerce/woocommerce.php');
activate_plugin('fames-mcp-gateway/fames-mcp-gateway.php');
WC_Install::install();
WC()->init();
WC_Post_Types::register_taxonomies();
WC_Post_Types::register_post_types();
WC_Post_Types::register_post_status();
WC()->load_rest_api();
WC_Install::create_roles();
// In-request activation needs a role-object refresh; native add_cap writes role data only.
$GLOBALS['wp_roles'] = new WP_Roles();
$admins=get_users(['role'=>'administrator','number'=>1]);
if (!$admins) throw new RuntimeException('No fixture administrator');
wp_set_current_user(0);
wp_set_current_user($admins[0]->ID);
update_option('fg_settings', ['enabled'=>true, 'writes'=>true, 'sensitive'=>true, 'users'=>[1], 'origins'=>[]]);
rest_get_server();
FG_WC_Tools::register();

$assertions = [];
function wc_assert($condition, $message) { global $assertions; if (!$condition) throw new RuntimeException($message); $assertions[] = $message; }
function wc_tool($name, $args) { $tool = FG_Tools::validate($name, $args); return ($tool['handler'])($args); }
function wc_reject($name, $args, $message) { try { wc_tool($name,$args); } catch (Throwable $e) { wc_assert(true,$message); return; } throw new RuntimeException($message); }

wc_reject('wc_orders_create', ['line_items'=>[['product_id'=>1,'quantity'=>1]],'set_paid'=>true], 'Order creation rejects set_paid');
wc_reject('wc_orders_create', ['line_items'=>[['product_id'=>1,'quantity'=>1,'meta_data'=>[]]]], 'Nested unknown order item fields rejected');
wc_reject('wc_products_create', ['name'=>'float price','regular_price'=>19.9], 'JSON-number price rejected');
wc_reject('wc_orders_update_status', ['id'=>1,'status'=>'refunded'], 'Refunded status rejected');
wc_reject('wc_orders_add_note', ['id'=>1,'note'=>'hi','customer_note'=>true], 'Customer notification flag rejected');

$product = wc_tool('wc_products_create', ['name'=>'Fixture coffee','regular_price'=>'10.00','manage_stock'=>true,'stock_quantity'=>100])['data'];
wc_assert($product['status']==='draft', 'Product defaults to draft');
wc_assert(!array_key_exists('meta_data',$product), 'Product response excludes metadata');
$pid=$product['id'];
wc_tool('wc_products_update',['id'=>$pid,'status'=>'publish']);
$coupon=wc_tool('wc_coupons_create',['code'=>'fixture10','discount_type'=>'percent','amount'=>'10','usage_limit'=>10])['data'];
wc_assert(!isset($coupon['used_by']) && !isset($coupon['email_restrictions']), 'Coupon response excludes redemption identities');

$order=wc_tool('wc_orders_create',[
 'line_items'=>[['product_id'=>$pid,'quantity'=>2]],
 'billing'=>['first_name'=>'Fixture','last_name'=>'Customer','email'=>'fixture@example.test','country'=>'MY'],
 'fee_lines'=>[['name'=>'Handling','total'=>'3.00','tax_status'=>'none']],
 'shipping_lines'=>[['method_id'=>'flat_rate','method_title'=>'Fixture shipping','total'=>'4.00']],
])['data'];
wc_assert($order['status']==='pending' && empty($order['date_paid_gmt']), 'Manual order is pending and unpaid');
wc_assert($order['total']==='27.00', 'Manual order includes product quantity, fee and shipping total');
wc_assert(!isset($order['order_key']) && !isset($order['meta_data']) && !isset($order['transaction_id']), 'Order response excludes secrets and metadata');
wc_assert(isset($order['line_items'][0]['id']) && !isset($order['line_items'][0]['meta_data']), 'Nested order field filtering retains IDs and excludes metadata');
$oid=$order['id']; $line=$order['line_items'][0]['id']; $fee=$order['fee_lines'][0]['id'];
$updated=wc_tool('wc_orders_update',['id'=>$oid,'line_items'=>[['id'=>$line,'quantity'=>3]],'fee_lines'=>[['id'=>$fee,'name'=>'Handling','total'=>'5.00','tax_status'=>'none']]])['data'];
wc_assert($updated['total']==='39.00', 'Existing quantity and fee changes recalculate total');
wc_reject('wc_orders_update',['id'=>$oid,'line_items'=>[['id'=>999999,'quantity'=>1]]], 'Foreign order line ID rejected before mutation');
$discounted=wc_tool('wc_orders_update',['id'=>$oid,'coupon_lines'=>[['code'=>'fixture10']]])['data'];
wc_assert($discounted['discount_total']==='3.00', 'Coupon code applies native checkout discount');
$rediscounted=wc_tool('wc_orders_update',['id'=>$oid,'line_items'=>[['id'=>$line,'quantity'=>4]]])['data'];
wc_assert($rediscounted['discount_total']==='4.00' && wc_format_decimal($rediscounted['coupon_lines'][0]['discount'],2)==='4.00' && $rediscounted['total']==='45.00', 'Quantity change recalculates existing coupon and preserves fee/shipping');
$custom=wc_tool('wc_orders_create',['line_items'=>[['product_id'=>$pid,'quantity'=>2]]])['data'];
$custom_order=wc_get_order($custom['id']);
$custom_line=$custom_order->get_item($custom['line_items'][0]['id']);
$custom_line->set_subtotal('15.00'); $custom_line->set_total('12.00'); $custom_line->save(); $custom_order->calculate_totals(); $custom_order->save();
$custom_changed=wc_tool('wc_orders_update',['id'=>$custom['id'],'line_items'=>[['id'=>$custom_line->get_id(),'quantity'=>3]]])['data'];
wc_assert($custom_changed['line_items'][0]['subtotal']==='22.50' && $custom_changed['line_items'][0]['total']==='18.00', 'Quantity change preserves a negotiated price and manual discount');
$note=wc_tool('wc_orders_add_note',['id'=>$oid,'note'=>'Internal fixture note'])['data'];
wc_assert($note['customer_note']===false, 'Order note remains internal');
wc_tool('wc_orders_update_status',['id'=>$oid,'status'=>'processing']);
wc_reject('wc_orders_update',['id'=>$oid,'billing'=>['city'=>'Kuala Lumpur']], 'Paid processing address edit blocked to preserve financial history');
wc_tool('wc_orders_update',['id'=>$oid,'customer_note'=>'Fixture note update']);
wc_assert(wc_get_order($oid)->get_customer_note()==='Fixture note update', 'Customer note remains editable after payment processing');

$variable=wc_tool('wc_products_create',['name'=>'Fixture variable','type'=>'variable','attributes'=>[['name'=>'Size','variation'=>true,'options'=>['Small','Large']]]])['data'];
$variation=wc_tool('wc_variations_create',['product_id'=>$variable['id'],'regular_price'=>'12.00','attributes'=>[['name'=>'Size','option'=>'Small']]])['data'];
wc_assert($variation['status']==='private', 'New variation defaults to private');
$trashed=wc_tool('wc_products_trash',['id'=>$pid])['data'];
wc_assert(get_post_status($pid)==='trash', 'Product trash preserves product post');
wc_tool('wc_coupons_trash',['id'=>$coupon['id']]);
wc_assert(get_post_status($coupon['id'])==='trash', 'Coupon trash preserves coupon post');

update_option('fg_settings',['sensitive'=>false]);
wc_reject('wc_orders_get',['id'=>$oid], 'Sensitive order tool blocked when sensitive access disabled');
echo json_encode(['assertions'=>count($assertions),'passed'=>$assertions],JSON_PRETTY_PRINT);
