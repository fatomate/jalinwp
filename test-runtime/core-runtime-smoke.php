<?php
require '/wordpress/wp-load.php';
require '/wordpress/wp-content/plugins/fames-mcp-gateway/includes/class-core.php';
wp_set_current_user(1);
FG_Core::activate();
foreach (['changes','audit','limits'] as $suffix) {
 $name = FG_Core::table($suffix);
 $found = $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SHOW TABLES LIKE %s', $name));
 if ($found !== $name) throw new RuntimeException('Missing table ' . $name . ': ' . $GLOBALS['wpdb']->last_error);
}
if (!FG_Core::audit('runtime_test','success')) throw new RuntimeException('Audit insert failed: ' . $GLOBALS['wpdb']->last_error);
if (!FG_Core::rate_limit()) throw new RuntimeException('Rate counter insert failed: ' . $GLOBALS['wpdb']->last_error);
echo json_encode(['tables_created'=>true, 'audit_inserted'=>true, 'rate_counter_works'=>true]);
